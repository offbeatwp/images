import { Button, FocalPointPicker } from '@wordpress/components';
import { useState, render } from '@wordpress/element';
import { close } from '@wordpress/icons';

import './style.scss';

const FocalPointModal = ({imageInfo, onClose, onChange}) => {
    const closeModal = () => {
        onClose();
    }

    const saveFocalPoint = () => {

        fetch(`${window.wpApiSettings.root}wp/v2/media/${imageInfo.id}`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.wpApiSettings.nonce
            },
            body: JSON.stringify({ meta: {focalpoint_x: focalPoint.x, focalpoint_y: focalPoint.y}} )
        })
        .then(response => response.json())
        .then(response => onChange({focalpoint_x: focalPoint.x, focalpoint_y: focalPoint.y}))
    }

    const [ focalPoint, setFocalPoint ] = useState( {
        x: imageInfo.focalpoint_x ?? 0.5,
        y: imageInfo.focalpoint_y ?? 0.5,
    } );

    const previewImageStyle = {
        backgroundImage: `url("${imageInfo.url}")`,
        backgroundPosition: `${ focalPoint.x * 100 }% ${ focalPoint.y * 100 }%`,
    };

    return (
        <>
        <div className="focalpoint-modal__modal">
            <div className="focalpoint-modal__head">
                <h1>Set focal point</h1>
                <div className="focalpoint-modal__head__actions">
                    <div>
                        <Button 
                            variant="primary"
                            text="Save"
                            onClick={ saveFocalPoint }
                        />
                    </div>
                    <Button 
                        icon={ close }
                        onClick={ closeModal }
                        className="focalpoint-modal__close"
                    />
                </div>
            </div>
            <div className="focalpoint-modal__body">
                <div class="focalpoint-modal__grid">
                    <div class="focalpoint-modal__picker">
                        <FocalPointPicker
                            url={imageInfo.url}
                            value={ focalPoint }
                            onDragStart={ setFocalPoint }
                            onDrag={ setFocalPoint }
                            onChange={ setFocalPoint }
                        />
                    </div>
                    <div class="focalpoint-modal__preview">
                        <div class="focalpoint-modal__preview__grid">
                            <div class="focalpoint-modal__preview__item focalpoint-modal__preview__item--square" style={previewImageStyle}></div>
                            <div class="focalpoint-modal__preview__item focalpoint-modal__preview__item--high" style={previewImageStyle}></div>
                            <div class="focalpoint-modal__preview__item focalpoint-modal__preview__item--wide" style={previewImageStyle}></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </>
    );
};

const focalPointApp =  {
    init() {
        document.addEventListener('click', (e) => {
            if (
                e.target.tagName !== 'BUTTON' ||
                !e.target.classList.contains('focal-point-modal-trigger') ||
                document.querySelector('focalpoint-modal')
            ) {
                return;
            }

            e.preventDefault();

            let imageInfo = JSON.parse(e.target.getAttribute('data-image-info'));

            const modalContainer = document.createElement('div');
            modalContainer.classList.add('focalpoint-modal');

            document.body.appendChild(modalContainer);
        
            render(
                <FocalPointModal
                    imageInfo={imageInfo}
                    onClose={() => {
                        modalContainer.remove();
                    }}
                    onChange={(value) => {
                        e.target.setAttribute('data-image-info', JSON.stringify(Object.assign(imageInfo, value)));

                        modalContainer.remove();
                    }}
                />,  
                modalContainer
            );
        });
    }
}

focalPointApp.init();